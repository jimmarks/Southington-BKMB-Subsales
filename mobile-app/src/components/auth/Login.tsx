import React, { useState } from 'react';
import { View, Text, TextInput, Button, StyleSheet, Image, ScrollView, Dimensions } from 'react-native';
import { useDispatch } from 'react-redux';
import { login } from '../../store/authSlice';

const { width } = Dimensions.get('window');

const Login = () => {
    const [code, setCode] = useState('');
    const [teamName, setTeamName] = useState('');
    const [imageError, setImageError] = useState(false);
    const dispatch = useDispatch();

    const handleLogin = () => {
        if (code && teamName) {
            dispatch(login({ code, teamName }));
        } else {
            alert('Please enter both code and team name');
        }
    };

    return (
        <ScrollView contentContainerStyle={styles.scrollContainer}>
            <View style={styles.container}>
                {/* Header Image */}
                <View style={styles.headerContainer}>
                    {!imageError ? (
                        <Image
                            source={{ uri: 'https://www.southingtonbkmb.com/wp-content/uploads/2024/05/cropped-cropped-cropped-Header_BKMB-Band-1.png' }}
                            style={styles.headerImage}
                            resizeMode="contain"
                            onError={() => setImageError(true)}
                        />
                    ) : (
                        <View style={styles.fallbackHeader}>
                            <Text style={styles.fallbackText}>BKMB</Text>
                            <Text style={styles.fallbackSubtext}>Southington Band</Text>
                        </View>
                    )}
                </View>
                
                {/* Login Form */}
                <View style={styles.formContainer}>
                    <Text style={styles.title}>Login</Text>
                    <TextInput
                        style={styles.input}
                        placeholder="Enter Code"
                        value={code}
                        onChangeText={setCode}
                    />
                    <TextInput
                        style={styles.input}
                        placeholder="Enter Team Name"
                        value={teamName}
                        onChangeText={setTeamName}
                    />
                    <Button title="Login" onPress={handleLogin} />
                </View>
            </View>
        </ScrollView>
    );
};

const styles = StyleSheet.create({
    scrollContainer: {
        flexGrow: 1,
        backgroundColor: '#f5f5f5',
    },
    container: {
        flex: 1,
        justifyContent: 'center',
        minHeight: Dimensions.get('window').height,
    },
    headerContainer: {
        alignItems: 'center',
        paddingTop: 40,
        paddingBottom: 20,
        paddingHorizontal: 16,
    },
    headerImage: {
        width: width * 0.9,
        height: 100,
        maxWidth: 400,
    },
    formContainer: {
        flex: 1,
        justifyContent: 'center',
        padding: 16,
        paddingTop: 20,
    },
    title: {
        fontSize: 24,
        fontWeight: 'bold',
        marginBottom: 32,
        textAlign: 'center',
        color: '#333',
    },
    input: {
        height: 48,
        borderColor: '#ddd',
        borderWidth: 1,
        marginBottom: 16,
        paddingHorizontal: 12,
        borderRadius: 8,
        backgroundColor: '#fff',
        fontSize: 16,
    },
    fallbackHeader: {
        alignItems: 'center',
        justifyContent: 'center',
        height: 100,
        width: width * 0.9,
        backgroundColor: '#1a365d',
        borderRadius: 8,
    },
    fallbackText: {
        fontSize: 28,
        fontWeight: 'bold',
        color: '#ffffff',
        letterSpacing: 2,
    },
    fallbackSubtext: {
        fontSize: 14,
        color: '#e2e8f0',
        marginTop: 4,
    },
});

export default Login;