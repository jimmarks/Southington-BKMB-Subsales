import React, { useState, useEffect } from 'react';
import { View, Text, TextInput, Button, StyleSheet, Alert } from 'react-native';
import { useDispatch, useSelector } from 'react-redux';
import { login } from '../store/authSlice';
import { setConfigSuccess, clearConfig } from '../store/configSlice';
import { login as authServiceLogin } from '../services/authService';
import { RootState } from '../store';

const AuthScreen = () => {
    const [code, setCode] = useState('');
    const [teamName, setTeamName] = useState('');
    const [isLoading, setIsLoading] = useState(false);
    
    const dispatch = useDispatch();
    const { isAuthenticated } = useSelector((state: RootState) => state.auth);
    const { isLoading: configLoading } = useSelector((state: RootState) => state.config);

    const handleLogin = async () => {
        if (!code || !teamName) {
            Alert.alert('Error', 'Please enter both code and team name');
            return;
        }

        setIsLoading(true);
        
        try {
            // Call auth service which handles both login and config fetch
            const result = await authServiceLogin(code, teamName);
            
            if (result.success) {
                // Update Redux auth state
                dispatch(login({ code, teamName }));
                
                // Update config if received
                if (result.config) {
                    dispatch(setConfigSuccess(result.config));
                }
                
                Alert.alert('Success', 'Login successful!');
            } else {
                Alert.alert('Error', result.message || 'Login failed');
            }
        } catch (error) {
            console.error('Login error:', error);
            Alert.alert('Error', 'Login failed. Please check your credentials and try again.');
            // Clear any existing config on login failure
            dispatch(clearConfig());
        } finally {
            setIsLoading(false);
        }
    };

    // Clear form when authenticated
    useEffect(() => {
        if (isAuthenticated) {
            setCode('');
            setTeamName('');
        }
    }, [isAuthenticated]);

    return (
        <View style={styles.container}>
            <Text style={styles.title}>BKMB Subsales Login</Text>
            
            <TextInput
                style={styles.input}
                placeholder="Enter Team Name"
                value={teamName}
                onChangeText={setTeamName}
                editable={!isLoading}
                autoCapitalize="words"
            />
            
            <TextInput
                style={styles.input}
                placeholder="Enter Access Code"
                value={code}
                onChangeText={setCode}
                editable={!isLoading}
                secureTextEntry
            />
            
            <Button 
                title={isLoading ? "Logging in..." : "Login"} 
                onPress={handleLogin}
                disabled={isLoading || configLoading}
            />
            
            {configLoading && (
                <Text style={styles.statusText}>Loading configuration...</Text>
            )}
        </View>
    );
};

const styles = StyleSheet.create({
    container: {
        flex: 1,
        justifyContent: 'center',
        padding: 16,
        backgroundColor: '#f5f5f5',
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
    statusText: {
        marginTop: 16,
        textAlign: 'center',
        color: '#666',
        fontSize: 14,
    },
});

export default AuthScreen;