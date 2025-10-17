import React, { useState } from 'react';
import { View, Text, TextInput, Button, StyleSheet } from 'react-native';
import { useDispatch } from 'react-redux';
import { login } from '../store/authSlice';

const AuthScreen = () => {
    const [code, setCode] = useState('');
    const [teamName, setTeamName] = useState('');
    const dispatch = useDispatch();

    const handleLogin = () => {
        if (code && teamName) {
            dispatch(login({ code, teamName }));
        } else {
            alert('Please enter both code and team name');
        }
    };

    return (
        <View style={styles.container}>
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

export default AuthScreen;