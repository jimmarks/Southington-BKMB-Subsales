import React, { useState } from 'react';
import { View, Text, TextInput, Button, StyleSheet, Alert } from 'react-native';
import { useDispatch } from 'react-redux';
import { login } from '../../services/authService';

const Login = () => {
    const [code, setCode] = useState('');
    const [teamName, setTeamName] = useState('');
    const dispatch = useDispatch();

    const handleLogin = async () => {
        if (!code || !teamName) {
            Alert.alert('Error', 'Please fill in all fields');
            return;
        }

        try {
            await dispatch(login({ code, teamName }));
            // Navigate to the next screen or show success message
        } catch (error) {
            Alert.alert('Login Failed', error.message);
        }
    };

    return (
        <View style={styles.container}>
            <Text style={styles.title}>Login</Text>
            <TextInput
                style={styles.input}
                placeholder="Code"
                value={code}
                onChangeText={setCode}
            />
            <TextInput
                style={styles.input}
                placeholder="Team Name"
                value={teamName}
                onChangeText={setTeamName}
            />
            <Button title="Login" onPress={handleLogin} />
        </View>
    );
};

const styles = StyleSheet.create({
    container: {
        flex: 1,
        justifyContent: 'center',
        padding: 16,
    },
    title: {
        fontSize: 24,
        marginBottom: 16,
        textAlign: 'center',
    },
    input: {
        height: 40,
        borderColor: 'gray',
        borderWidth: 1,
        marginBottom: 12,
        paddingHorizontal: 8,
    },
});

export default Login;